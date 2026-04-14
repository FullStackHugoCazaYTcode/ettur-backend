<?php
/**
 * ETTUR - API de Pagos
 * Core del sistema: registro, validación correlativa, aprobación/rechazo
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helpers.php';
require_once __DIR__ . '/../../middleware/Auth.php';
require_once __DIR__ . '/../tarifas/index.php';

cors_headers();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'periodos-pendientes':
        handle_periodos_pendientes();
        break;
    case 'registrar':
        if ($method !== 'POST') error_response('Método no permitido', 405);
        handle_registrar_pago();
        break;
    case 'mis-pagos':
        handle_mis_pagos();
        break;
    case 'pendientes':
        handle_pagos_pendientes();
        break;
    case 'validar':
        if ($method !== 'POST') error_response('Método no permitido', 405);
        handle_validar_pago();
        break;
    case 'detalle':
        handle_detalle_pago();
        break;
    case 'comprobante':
        handle_ver_comprobante();
        break;
    case 'historial':
        handle_historial();
        break;
    default:
        error_response('Acción no válida', 404);
}

/**
 * Generar periodos quincenales entre dos fechas
 */
function generar_periodos($fecha_inicio, $fecha_fin) {
    $periodos = [];
    $current = new DateTime($fecha_inicio);
    $end = new DateTime($fecha_fin);

    // Ajustar al inicio del primer periodo
    $dia = (int)$current->format('j');
    if ($dia > 15) {
        $current->modify('first day of next month');
    } elseif ($dia > 1) {
        $current->setDate((int)$current->format('Y'), (int)$current->format('n'), 1);
    }

    while ($current <= $end) {
        $year = (int)$current->format('Y');
        $month = (int)$current->format('n');

        // Primera quincena (1-15)
        $q1_inicio = sprintf('%04d-%02d-01', $year, $month);
        $q1_fin = sprintf('%04d-%02d-15', $year, $month);
        $q1_end_dt = new DateTime($q1_fin);

        if (new DateTime($q1_inicio) >= new DateTime($fecha_inicio) && $q1_end_dt <= $end) {
            $tarifa = get_tarifa_actual($q1_inicio);
            $periodos[] = [
                'anio' => $year,
                'mes' => $month,
                'quincena' => 1,
                'fecha_inicio' => $q1_inicio,
                'fecha_fin' => $q1_fin,
                'tipo_tarifa' => $tarifa['tipo'],
                'monto' => $tarifa['monto']
            ];
        }

        // Segunda quincena (16 - último día)
        $last_day = (int)$current->format('t');
        $q2_inicio = sprintf('%04d-%02d-16', $year, $month);
        $q2_fin = sprintf('%04d-%02d-%02d', $year, $month, $last_day);
        $q2_end_dt = new DateTime($q2_fin);

        if (new DateTime($q2_inicio) >= new DateTime($fecha_inicio) && $q2_end_dt <= $end) {
            $tarifa = get_tarifa_actual($q2_inicio);
            $periodos[] = [
                'anio' => $year,
                'mes' => $month,
                'quincena' => 2,
                'fecha_inicio' => $q2_inicio,
                'fecha_fin' => $q2_fin,
                'tipo_tarifa' => $tarifa['tipo'],
                'monto' => $tarifa['monto']
            ];
        }

        $current->modify('first day of next month');
    }

    return $periodos;
}

/**
 * Asegurar que un periodo existe en la BD
 */
function ensure_periodo($periodo_data) {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT id FROM periodos_pago WHERE anio = ? AND mes = ? AND quincena = ?");
    $stmt->execute([$periodo_data['anio'], $periodo_data['mes'], $periodo_data['quincena']]);
    $existing = $stmt->fetch();

    if ($existing) return (int)$existing['id'];

    $stmt2 = $pdo->prepare("
        INSERT INTO periodos_pago (anio, mes, quincena, fecha_inicio, fecha_fin, tipo_tarifa, monto)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt2->execute([
        $periodo_data['anio'],
        $periodo_data['mes'],
        $periodo_data['quincena'],
        $periodo_data['fecha_inicio'],
        $periodo_data['fecha_fin'],
        $periodo_data['tipo_tarifa'],
        $periodo_data['monto']
    ]);

    return (int)$pdo->lastInsertId();
}

/**
 * Obtener periodos pendientes de un trabajador (con validación correlativa)
 */
function handle_periodos_pendientes() {
    $user = Auth::requireAuth();

    $trabajador_id = $_GET['trabajador_id'] ?? $user['id'];

    // Solo admin/coadmin pueden ver periodos de otros
    if ($trabajador_id != $user['id'] && !in_array($user['rol_nombre'], ['admin', 'coadmin'])) {
        error_response('No tiene permisos', 403);
    }

    $pdo = db();

    // Obtener fecha de inicio de cobro
    $stmt = $pdo->prepare("SELECT fecha_inicio_cobro FROM trabajador_config WHERE usuario_id = ?");
    $stmt->execute([$trabajador_id]);
    $config = $stmt->fetch();

    if (!$config) {
        error_response('No se ha configurado la fecha de inicio de cobro para este trabajador');
    }

    $fecha_inicio = $config['fecha_inicio_cobro'];
    $fecha_hoy = date('Y-m-d');

    // Generar todos los periodos desde inicio hasta hoy
    $todos_periodos = generar_periodos($fecha_inicio, $fecha_hoy);

    // Obtener pagos ya registrados
    $stmt2 = $pdo->prepare("
        SELECT p.periodo_id, p.estado, pp.anio, pp.mes, pp.quincena
        FROM pagos p
        JOIN periodos_pago pp ON p.periodo_id = pp.id
        WHERE p.trabajador_id = ?
    ");
    $stmt2->execute([$trabajador_id]);
    $pagos_existentes = $stmt2->fetchAll();

    $pagados = [];
    foreach ($pagos_existentes as $pe) {
        $key = $pe['anio'] . '-' . $pe['mes'] . '-' . $pe['quincena'];
        $pagados[$key] = $pe['estado'];
    }

    // Filtrar periodos pendientes
    $pendientes = [];
    $deuda_total = 0;

    foreach ($todos_periodos as $periodo) {
        $key = $periodo['anio'] . '-' . $periodo['mes'] . '-' . $periodo['quincena'];
        if (!isset($pagados[$key]) || $pagados[$key] === 'rechazado') {
            $pendientes[] = $periodo;
            $deuda_total += $periodo['monto'];
        }
    }

    // Verificación correlativa: solo el primer periodo pendiente puede pagarse
    $primer_pendiente = !empty($pendientes) ? $pendientes[0] : null;

    success_response([
        'fecha_inicio_cobro' => $fecha_inicio,
        'periodos_pendientes' => $pendientes,
        'total_deuda' => round($deuda_total, 2),
        'periodo_siguiente_pago' => $primer_pendiente,
        'total_periodos_pendientes' => count($pendientes)
    ]);
}

/**
 * Registrar un nuevo pago
 */
function handle_registrar_pago() {
    $user = Auth::requireAuth();

    // Verificar que es trabajador o admin registrando para otro
    $trabajador_id = $user['id'];
    if (in_array($user['rol_nombre'], ['admin', 'coadmin']) && !empty($_POST['trabajador_id'])) {
        $trabajador_id = (int)$_POST['trabajador_id'];
    } elseif ($user['rol_nombre'] !== 'trabajador') {
        $trabajador_id = (int)($_POST['trabajador_id'] ?? 0);
        if (!$trabajador_id) error_response('Debe especificar el trabajador');
    }

    // Validar datos del periodo
    $anio = (int)($_POST['anio'] ?? 0);
    $mes = (int)($_POST['mes'] ?? 0);
    $quincena = (int)($_POST['quincena'] ?? 0);
    $metodo_pago = sanitize_string($_POST['metodo_pago'] ?? 'yape');
    $observaciones = sanitize_string($_POST['observaciones'] ?? '');

    if (!$anio || !$mes || !$quincena) {
        error_response('Datos del periodo incompletos');
    }

    if (!in_array($metodo_pago, ['yape', 'transferencia', 'efectivo'])) {
        error_response('Método de pago no válido');
    }

    $pdo = db();

    // Verificar fecha de inicio de cobro
    $stmt = $pdo->prepare("SELECT fecha_inicio_cobro FROM trabajador_config WHERE usuario_id = ?");
    $stmt->execute([$trabajador_id]);
    $config = $stmt->fetch();
    if (!$config) error_response('Trabajador sin fecha de inicio de cobro configurada');

    // VALIDACIÓN CORRELATIVA: Verificar que no hay periodos anteriores sin pagar
    $fecha_inicio = $config['fecha_inicio_cobro'];
    $todos_periodos = generar_periodos($fecha_inicio, date('Y-m-d'));

    $target_found = false;
    foreach ($todos_periodos as $per) {
        if ($per['anio'] == $anio && $per['mes'] == $mes && $per['quincena'] == $quincena) {
            $target_found = true;
            break;
        }

        // Verificar si este periodo previo está pagado
        $stmt_check = $pdo->prepare("
            SELECT p.estado FROM pagos p
            JOIN periodos_pago pp ON p.periodo_id = pp.id
            WHERE p.trabajador_id = ? AND pp.anio = ? AND pp.mes = ? AND pp.quincena = ?
            AND p.estado IN ('pendiente', 'aprobado')
        ");
        $stmt_check->execute([$trabajador_id, $per['anio'], $per['mes'], $per['quincena']]);
        $pago_previo = $stmt_check->fetch();

        if (!$pago_previo) {
            $nombre_mes = [1=>'Ene',2=>'Feb',3=>'Mar',4=>'Abr',5=>'May',6=>'Jun',7=>'Jul',8=>'Ago',9=>'Sep',10=>'Oct',11=>'Nov',12=>'Dic'];
            $q_text = $per['quincena'] == 1 ? '1ra' : '2da';
            error_response(
                "Bloqueo correlativo: Debe pagar primero el periodo " .
                $q_text . " quincena de " . ($nombre_mes[$per['mes']] ?? $per['mes']) . " " . $per['anio']
            );
        }
    }

    if (!$target_found) {
        error_response('El periodo especificado no corresponde o aún no está disponible');
    }

    // Verificar que no existe pago aprobado/pendiente para este periodo
    $periodo_data = null;
    foreach ($todos_periodos as $per) {
        if ($per['anio'] == $anio && $per['mes'] == $mes && $per['quincena'] == $quincena) {
            $periodo_data = $per;
            break;
        }
    }

    $periodo_id = ensure_periodo($periodo_data);

    $stmt_dup = $pdo->prepare("SELECT id, estado FROM pagos WHERE trabajador_id = ? AND periodo_id = ? AND estado IN ('pendiente', 'aprobado')");
    $stmt_dup->execute([$trabajador_id, $periodo_id]);
    $dup = $stmt_dup->fetch();
    if ($dup) {
        error_response('Ya existe un pago ' . $dup['estado'] . ' para este periodo');
    }

    // Procesar comprobante (imagen)
    $comprobante_url = null;
    $comprobante_nombre = null;

    if (isset($_FILES['comprobante']) && $_FILES['comprobante']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['comprobante'];

        // Validar tamaño
        if ($file['size'] > MAX_FILE_SIZE) {
            error_response('El archivo excede el tamaño máximo de 5MB');
        }

        // Validar extensión
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ALLOWED_EXTENSIONS)) {
            error_response('Formato de archivo no permitido. Use JPG, PNG o WebP');
        }

        // Crear directorio si no existe
        $upload_dir = UPLOAD_DIR . date('Y/m/');
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }

        // Nombre único
        $filename = uniqid('comp_') . '_' . $trabajador_id . '.' . $ext;
        $filepath = $upload_dir . $filename;

        if (!move_uploaded_file($file['tmp_name'], $filepath)) {
            error_response('Error al subir el comprobante');
        }

        $comprobante_url = 'uploads/' . date('Y/m/') . $filename;
        $comprobante_nombre = $file['name'];
    }

    // Registrar pago
    $stmt_insert = $pdo->prepare("
        INSERT INTO pagos (trabajador_id, periodo_id, monto_pagado, metodo_pago, comprobante_url, comprobante_nombre, observaciones)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt_insert->execute([
        $trabajador_id,
        $periodo_id,
        $periodo_data['monto'],
        $metodo_pago,
        $comprobante_url,
        $comprobante_nombre,
        $observaciones
    ]);

    $pago_id = $pdo->lastInsertId();

    registrar_auditoria($user['id'], 'REGISTRAR_PAGO', 'pagos', $pago_id, null, [
        'trabajador_id' => $trabajador_id,
        'periodo' => "$anio-$mes-Q$quincena",
        'monto' => $periodo_data['monto'],
        'metodo' => $metodo_pago
    ]);

    success_response([
        'pago_id' => (int)$pago_id,
        'periodo' => "$anio-$mes-Q$quincena",
        'monto' => $periodo_data['monto']
    ], 'Pago registrado correctamente. Pendiente de aprobación.', 201);
}

/**
 * Mis pagos (para trabajador)
 */
function handle_mis_pagos() {
    $user = Auth::requireAuth();
    $trabajador_id = $_GET['trabajador_id'] ?? $user['id'];

    if ($trabajador_id != $user['id'] && !in_array($user['rol_nombre'], ['admin', 'coadmin'])) {
        error_response('No tiene permisos', 403);
    }

    $pdo = db();
    $estado_filter = $_GET['estado'] ?? '';

    $where = "p.trabajador_id = ?";
    $params = [$trabajador_id];

    if ($estado_filter && in_array($estado_filter, ['pendiente', 'aprobado', 'rechazado'])) {
        $where .= " AND p.estado = ?";
        $params[] = $estado_filter;
    }

    $stmt = $pdo->prepare("
        SELECT p.id, p.monto_pagado, p.metodo_pago, p.estado, p.fecha_pago,
               p.fecha_validacion, p.observaciones, p.observacion_rechazo,
               p.comprobante_url, p.comprobante_nombre,
               pp.anio, pp.mes, pp.quincena, pp.tipo_tarifa,
               CONCAT(v.nombres, ' ', v.apellidos) as validado_por_nombre
        FROM pagos p
        JOIN periodos_pago pp ON p.periodo_id = pp.id
        LEFT JOIN usuarios v ON p.validado_por = v.id
        WHERE $where
        ORDER BY pp.anio DESC, pp.mes DESC, pp.quincena DESC
    ");
    $stmt->execute($params);

    success_response($stmt->fetchAll());
}

/**
 * Pagos pendientes de validación (admin/coadmin)
 */
function handle_pagos_pendientes() {
    $user = Auth::requireRole(['admin', 'coadmin']);
    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT p.id, p.monto_pagado, p.metodo_pago, p.estado, p.fecha_pago,
               p.comprobante_url, p.comprobante_nombre, p.observaciones,
               pp.anio, pp.mes, pp.quincena, pp.tipo_tarifa,
               CONCAT(t.nombres, ' ', t.apellidos) as trabajador_nombre,
               t.dni as trabajador_dni, t.id as trabajador_id
        FROM pagos p
        JOIN periodos_pago pp ON p.periodo_id = pp.id
        JOIN usuarios t ON p.trabajador_id = t.id
        WHERE p.estado = 'pendiente'
        ORDER BY p.fecha_pago ASC
    ");
    $stmt->execute();

    success_response($stmt->fetchAll());
}

/**
 * Validar (aprobar/rechazar) un pago
 */
function handle_validar_pago() {
    $user = Auth::requireRole(['admin', 'coadmin']);
    $data = get_json_input();

    $errors = validate_required($data, ['pago_id', 'accion']);
    if (!empty($errors)) error_response('Datos incompletos', 400, $errors);

    $pago_id = (int)$data['pago_id'];
    $accion = $data['accion']; // 'aprobar' o 'rechazar'

    if (!in_array($accion, ['aprobar', 'rechazar'])) {
        error_response('Acción no válida. Use "aprobar" o "rechazar"');
    }

    $pdo = db();
    $stmt = $pdo->prepare("SELECT * FROM pagos WHERE id = ? AND estado = 'pendiente'");
    $stmt->execute([$pago_id]);
    $pago = $stmt->fetch();

    if (!$pago) error_response('Pago no encontrado o ya fue procesado', 404);

    $nuevo_estado = $accion === 'aprobar' ? 'aprobado' : 'rechazado';
    $observacion_rechazo = ($accion === 'rechazar') ? sanitize_string($data['motivo_rechazo'] ?? '') : null;

    $stmt2 = $pdo->prepare("
        UPDATE pagos SET estado = ?, fecha_validacion = NOW(), validado_por = ?, observacion_rechazo = ?
        WHERE id = ?
    ");
    $stmt2->execute([$nuevo_estado, $user['id'], $observacion_rechazo, $pago_id]);

    registrar_auditoria($user['id'], 'VALIDAR_PAGO_' . strtoupper($accion), 'pagos', $pago_id, $pago, [
        'estado' => $nuevo_estado,
        'motivo_rechazo' => $observacion_rechazo
    ]);

    $msg = $accion === 'aprobar' ? 'Pago aprobado correctamente' : 'Pago rechazado';
    success_response(['estado' => $nuevo_estado], $msg);
}

function handle_detalle_pago() {
    $user = Auth::requireAuth();
    $id = $_GET['id'] ?? 0;
    if (!$id) error_response('ID requerido');

    $pdo = db();
    $stmt = $pdo->prepare("
        SELECT p.*, pp.anio, pp.mes, pp.quincena, pp.tipo_tarifa,
               CONCAT(t.nombres, ' ', t.apellidos) as trabajador_nombre,
               t.dni as trabajador_dni,
               CONCAT(v.nombres, ' ', v.apellidos) as validado_por_nombre
        FROM pagos p
        JOIN periodos_pago pp ON p.periodo_id = pp.id
        JOIN usuarios t ON p.trabajador_id = t.id
        LEFT JOIN usuarios v ON p.validado_por = v.id
        WHERE p.id = ?
    ");
    $stmt->execute([$id]);
    $pago = $stmt->fetch();

    if (!$pago) error_response('Pago no encontrado', 404);

    // Trabajador solo ve sus propios pagos
    if ($user['rol_nombre'] === 'trabajador' && $pago['trabajador_id'] != $user['id']) {
        error_response('No tiene permisos', 403);
    }

    success_response($pago);
}

function handle_ver_comprobante() {
    Auth::requireAuth();
    $path = $_GET['path'] ?? '';
    if (!$path) error_response('Ruta del comprobante requerida');

    $filepath = __DIR__ . '/../../' . $path;
    if (!file_exists($filepath)) error_response('Comprobante no encontrado', 404);

    $mime = mime_content_type($filepath);
    header("Content-Type: $mime");
    header("Content-Disposition: inline");
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

    if ($trabajador_id) {
        $where .= " AND p.trabajador_id = ?";
        $params[] = (int)$trabajador_id;
    }

    if ($estado && in_array($estado, ['pendiente', 'aprobado', 'rechazado'])) {
        $where .= " AND p.estado = ?";
        $params[] = $estado;
    }

    $stmt = $pdo->prepare("
        SELECT p.id, p.monto_pagado, p.metodo_pago, p.estado, p.fecha_pago,
               p.fecha_validacion, p.observaciones, p.observacion_rechazo,
               pp.anio, pp.mes, pp.quincena, pp.tipo_tarifa,
               CONCAT(t.nombres, ' ', t.apellidos) as trabajador_nombre,
               t.dni as trabajador_dni
        FROM pagos p
        JOIN periodos_pago pp ON p.periodo_id = pp.id
        JOIN usuarios t ON p.trabajador_id = t.id
        WHERE $where
        ORDER BY p.fecha_pago DESC
    ");
    $stmt->execute($params);
    $pagos = $stmt->fetchAll();

    // Calcular resumen
    $total_aprobado = 0;
    $total_pendiente = 0;
    $total_rechazado = 0;

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
