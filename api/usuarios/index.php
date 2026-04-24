<?php
/**
 * ETTUR - API Gestión de Usuarios v3.0
 * Con fecha lanzamiento app (dos carriles)
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helpers.php';
require_once __DIR__ . '/../../middleware/Auth.php';

cors_headers();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'list';

switch ($action) {
    case 'list':    handle_list(); break;
    case 'get':     handle_get(); break;
    case 'create':  if ($method !== 'POST') error_response('Método no permitido', 405); handle_create(); break;
    case 'update':  if ($method !== 'PUT' && $method !== 'POST') error_response('Método no permitido', 405); handle_update(); break;
    case 'toggle':  if ($method !== 'POST') error_response('Método no permitido', 405); handle_toggle(); break;
    case 'delete':  if ($method !== 'POST') error_response('Método no permitido', 405); handle_delete(); break;
    case 'reset-placa': if ($method !== 'POST') error_response('Método no permitido', 405); handle_reset_placa(); break;
    default: error_response('Acción no válida', 404);
}

function handle_list() {
    $user = Auth::requireRole(['admin', 'coadmin']);
    $pdo = db();
    $rol_filter = $_GET['rol'] ?? '';
    $activo_filter = $_GET['activo'] ?? '';
    $search = $_GET['search'] ?? '';
    $where = ["1=1"];
    $params = [];
    if ($rol_filter) { $where[] = "r.nombre = ?"; $params[] = $rol_filter; }
    if ($activo_filter !== '') { $where[] = "u.activo = ?"; $params[] = (int)$activo_filter; }
    if ($search) {
        $where[] = "(u.nombres LIKE ? OR u.apellidos LIKE ? OR u.dni LIKE ? OR u.placa LIKE ?)";
        $s = "%$search%"; array_push($params, $s, $s, $s, $s);
    }
    $where_sql = implode(' AND ', $where);
    $stmt = $pdo->prepare("
        SELECT u.id, u.nombres, u.apellidos, u.dni, u.placa, u.telefono, u.email,
               u.username, u.activo, u.fecha_registro, u.fecha_baja,
               u.tipo_trabajador, u.monto_personalizado, u.frecuencia_personalizado,
               r.nombre as rol, r.id as rol_id,
               tc.fecha_inicio_cobro, tc.fecha_lanzamiento
        FROM usuarios u
        JOIN roles r ON u.rol_id = r.id
        LEFT JOIN trabajador_config tc ON u.id = tc.usuario_id
        WHERE $where_sql
        ORDER BY r.id ASC, u.apellidos ASC, u.nombres ASC
    ");
    $stmt->execute($params);
    $usuarios = $stmt->fetchAll();
    foreach ($usuarios as &$u) {
        $u['id'] = (int)$u['id'];
        $u['rol_id'] = (int)$u['rol_id'];
        $u['activo'] = (bool)$u['activo'];
    }
    success_response($usuarios);
}

function handle_get() {
    $user = Auth::requireRole(['admin', 'coadmin']);
    $id = $_GET['id'] ?? 0;
    if (!$id) error_response('ID requerido');
    $pdo = db();
    $stmt = $pdo->prepare("
        SELECT u.id, u.nombres, u.apellidos, u.dni, u.placa, u.telefono, u.email,
               u.username, u.activo, u.fecha_registro, u.fecha_baja,
               u.tipo_trabajador, u.monto_personalizado, u.frecuencia_personalizado,
               r.nombre as rol, r.id as rol_id,
               tc.fecha_inicio_cobro, tc.fecha_lanzamiento, tc.notas as config_notas
        FROM usuarios u
        JOIN roles r ON u.rol_id = r.id
        LEFT JOIN trabajador_config tc ON u.id = tc.usuario_id
        WHERE u.id = ?
    ");
    $stmt->execute([$id]);
    $usuario = $stmt->fetch();
    if (!$usuario) error_response('Usuario no encontrado', 404);
    $usuario['id'] = (int)$usuario['id'];
    $usuario['rol_id'] = (int)$usuario['rol_id'];
    $usuario['activo'] = (bool)$usuario['activo'];
    success_response($usuario);
}

function handle_create() {
    $admin = Auth::requireRole('admin');
    $data = get_json_input();
    $errors = validate_required($data, ['nombres', 'apellidos', 'dni', 'placa', 'rol_id']);
    if (!empty($errors)) error_response('Datos incompletos', 400, $errors);
    if (!preg_match('/^\d{8}$/', $data['dni'])) error_response('El DNI debe tener 8 dígitos');
    $placa = strtoupper(sanitize_string($data['placa']));
    if (empty($placa)) error_response('La placa es obligatoria');
    $rol_id = (int)$data['rol_id'];
    if (!in_array($rol_id, [1, 2, 3])) error_response('Rol no válido');

    $tipo_trabajador = null;
    $monto_personalizado = null;
    $frecuencia_personalizado = null;
    if ($rol_id == 3) {
        $tipo_trabajador = $data['tipo_trabajador'] ?? 'normal';
        if (!in_array($tipo_trabajador, ['normal', 'especial', 'mensual', 'personalizado'])) error_response('Tipo no válido');
        if ($tipo_trabajador === 'personalizado') {
            if (empty($data['monto_personalizado']) || $data['monto_personalizado'] <= 0) error_response('Monto personalizado requerido');
            $monto_personalizado = (float)$data['monto_personalizado'];
            $frecuencia_personalizado = $data['frecuencia_personalizado'] ?? 'semanal';
            if (!in_array($frecuencia_personalizado, ['semanal', 'mensual'])) error_response('Frecuencia no válida');
        }
    }

    $pdo = db();
    $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE dni = ?");
    $stmt->execute([$data['dni']]);
    if ($stmt->fetch()) error_response('Ya existe un usuario con ese DNI');
    $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE UPPER(placa) = ?");
    $stmt->execute([$placa]);
    if ($stmt->fetch()) error_response('Ya existe un usuario con esa placa');

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("
            INSERT INTO usuarios (rol_id, nombres, apellidos, dni, placa, telefono, email, username,
                                  password_hash, tipo_trabajador, monto_personalizado, frecuencia_personalizado, registrado_por)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $rol_id, sanitize_string($data['nombres']), sanitize_string($data['apellidos']),
            $data['dni'], $placa, sanitize_string($data['telefono'] ?? ''),
            sanitize_string($data['email'] ?? ''), $data['dni'],
            password_hash($data['dni'], PASSWORD_BCRYPT),
            $tipo_trabajador, $monto_personalizado, $frecuencia_personalizado, $admin['id']
        ]);
        $new_id = $pdo->lastInsertId();

        if ($rol_id == 3 && !empty($data['fecha_inicio_cobro'])) {
            $fecha_lanzamiento = !empty($data['fecha_lanzamiento']) ? $data['fecha_lanzamiento'] : $data['fecha_inicio_cobro'];
            $stmt2 = $pdo->prepare("
                INSERT INTO trabajador_config (usuario_id, fecha_inicio_cobro, fecha_lanzamiento, notas, configurado_por)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt2->execute([
                $new_id, $data['fecha_inicio_cobro'], $fecha_lanzamiento,
                sanitize_string($data['notas_config'] ?? ''), $admin['id']
            ]);
        }

        $pdo->commit();
        registrar_auditoria($admin['id'], 'CREAR_USUARIO', 'usuarios', $new_id, null, [
            'nombres' => $data['nombres'], 'tipo_trabajador' => $tipo_trabajador, 'placa' => $placa
        ]);
        success_response(['id' => (int)$new_id], 'Usuario creado correctamente', 201);
    } catch (Exception $e) {
        $pdo->rollBack();
        error_response('Error al crear: ' . $e->getMessage(), 500);
    }
}

function handle_update() {
    $admin = Auth::requireRole('admin');
    $data = get_json_input();
    if (empty($data['id'])) error_response('ID requerido');
    $id = (int)$data['id'];
    $pdo = db();
    $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE id = ?");
    $stmt->execute([$id]);
    $current = $stmt->fetch();
    if (!$current) error_response('Usuario no encontrado', 404);

    $pdo->beginTransaction();
    try {
        $fields = [];
        $params = [];
        $updatable = ['nombres', 'apellidos', 'dni', 'telefono', 'email', 'rol_id',
                       'tipo_trabajador', 'monto_personalizado', 'frecuencia_personalizado'];
        foreach ($updatable as $field) {
            if (isset($data[$field])) {
                $fields[] = "$field = ?";
                $params[] = $field === 'rol_id' ? (int)$data[$field] : $data[$field];
            }
        }
        if (isset($data['placa'])) {
            $placa = strtoupper(sanitize_string($data['placa']));
            $stmtP = $pdo->prepare("SELECT id FROM usuarios WHERE UPPER(placa) = ? AND id != ?");
            $stmtP->execute([$placa, $id]);
            if ($stmtP->fetch()) error_response('Placa en uso');
            $fields[] = "placa = ?";
            $params[] = $placa;
        }
        if (!empty($fields)) {
            $params[] = $id;
            $pdo->prepare("UPDATE usuarios SET " . implode(', ', $fields) . " WHERE id = ?")->execute($params);
        }

        // Actualizar config de trabajador
        if (isset($data['fecha_inicio_cobro']) || isset($data['fecha_lanzamiento'])) {
            $fecha_inicio = $data['fecha_inicio_cobro'] ?? null;
            $fecha_lanzamiento = $data['fecha_lanzamiento'] ?? null;

            // Si no tiene config, la fecha_lanzamiento por defecto es igual a fecha_inicio
            if ($fecha_inicio && !$fecha_lanzamiento) {
                $fecha_lanzamiento = $fecha_inicio;
            }

            $stmt2 = $pdo->prepare("
                INSERT INTO trabajador_config (usuario_id, fecha_inicio_cobro, fecha_lanzamiento, notas, configurado_por)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                fecha_inicio_cobro = COALESCE(VALUES(fecha_inicio_cobro), fecha_inicio_cobro),
                fecha_lanzamiento = COALESCE(VALUES(fecha_lanzamiento), fecha_lanzamiento),
                notas = VALUES(notas), configurado_por = VALUES(configurado_por), fecha_configuracion = NOW()
            ");
            $stmt2->execute([
                $id, $fecha_inicio, $fecha_lanzamiento,
                sanitize_string($data['notas_config'] ?? ''), $admin['id']
            ]);
        }

        $pdo->commit();
        registrar_auditoria($admin['id'], 'EDITAR_USUARIO', 'usuarios', $id, $current, $data);
        success_response(null, 'Usuario actualizado correctamente');
    } catch (Exception $e) {
        $pdo->rollBack();
        error_response('Error: ' . $e->getMessage(), 500);
    }
}

function handle_toggle() {
    $admin = Auth::requireRole('admin');
    $data = get_json_input();
    if (empty($data['id'])) error_response('ID requerido');
    $id = (int)$data['id'];
    if ($id === (int)$admin['id']) error_response('No puede desactivarse a sí mismo');
    $pdo = db();
    $stmt = $pdo->prepare("SELECT id, activo FROM usuarios WHERE id = ?");
    $stmt->execute([$id]);
    $user = $stmt->fetch();
    if (!$user) error_response('No encontrado', 404);
    $new_status = $user['activo'] ? 0 : 1;
    $fecha_baja = $new_status ? null : date('Y-m-d H:i:s');
    $pdo->prepare("UPDATE usuarios SET activo = ?, fecha_baja = ? WHERE id = ?")->execute([$new_status, $fecha_baja, $id]);
    if (!$new_status) { $pdo->prepare("UPDATE sesiones SET activo = 0 WHERE usuario_id = ?")->execute([$id]); }
    registrar_auditoria($admin['id'], $new_status ? 'ACTIVAR' : 'DESACTIVAR', 'usuarios', $id);
    success_response(['activo' => (bool)$new_status], $new_status ? 'Activado' : 'Dado de baja');
}

function handle_delete() {
    $admin = Auth::requireRole('admin');
    $data = get_json_input();
    if (empty($data['id'])) error_response('ID requerido');
    $id = (int)$data['id'];
    if ($id === (int)$admin['id']) error_response('No puede eliminarse a sí mismo');
    $pdo = db();
    $stmt = $pdo->prepare("SELECT id, nombres, apellidos, rol_id FROM usuarios WHERE id = ?");
    $stmt->execute([$id]);
    $user = $stmt->fetch();
    if (!$user) error_response('No encontrado', 404);
    if ($user['rol_id'] == 1) error_response('No se puede eliminar un administrador');
    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM sesiones WHERE usuario_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM trabajador_config WHERE usuario_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM pagos WHERE trabajador_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM usuarios WHERE id = ?")->execute([$id]);
        $pdo->commit();
        registrar_auditoria($admin['id'], 'ELIMINAR_USUARIO', 'usuarios', $id, $user);
        success_response(null, 'Usuario eliminado permanentemente');
    } catch (Exception $e) {
        $pdo->rollBack();
        error_response('Error: ' . $e->getMessage(), 500);
    }
}

function handle_reset_placa() {
    $admin = Auth::requireRole('admin');
    $data = get_json_input();
    if (empty($data['id']) || empty($data['placa'])) error_response('ID y placa requeridos');
    $placa = strtoupper(sanitize_string($data['placa']));
    $id = (int)$data['id'];
    $pdo = db();
    $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE UPPER(placa) = ? AND id != ?");
    $stmt->execute([$placa, $id]);
    if ($stmt->fetch()) error_response('Placa en uso');
    $pdo->prepare("UPDATE usuarios SET placa = ? WHERE id = ?")->execute([$placa, $id]);
    registrar_auditoria($admin['id'], 'RESET_PLACA', 'usuarios', $id);
    success_response(null, 'Placa actualizada');
}
