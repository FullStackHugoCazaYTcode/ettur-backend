<?php
/**
 * ETTUR - API de Gestión de Usuarios
 * Solo Admin puede crear/editar/desactivar usuarios
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helpers.php';
require_once __DIR__ . '/../../middleware/Auth.php';

cors_headers();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'list';

switch ($action) {
    case 'list':
        if ($method !== 'GET') error_response('Método no permitido', 405);
        handle_list();
        break;
    case 'get':
        if ($method !== 'GET') error_response('Método no permitido', 405);
        handle_get();
        break;
    case 'create':
        if ($method !== 'POST') error_response('Método no permitido', 405);
        handle_create();
        break;
    case 'update':
        if ($method !== 'PUT' && $method !== 'POST') error_response('Método no permitido', 405);
        handle_update();
        break;
    case 'toggle':
        if ($method !== 'POST') error_response('Método no permitido', 405);
        handle_toggle();
        break;
    case 'reset-password':
        if ($method !== 'POST') error_response('Método no permitido', 405);
        handle_reset_password();
        break;
    default:
        error_response('Acción no válida', 404);
}

function handle_list() {
    $user = Auth::requireRole(['admin', 'coadmin']);
    $pdo = db();

    $rol_filter = $_GET['rol'] ?? '';
    $activo_filter = $_GET['activo'] ?? '';
    $search = $_GET['search'] ?? '';

    $where = ["1=1"];
    $params = [];

    if ($rol_filter) {
        $where[] = "r.nombre = ?";
        $params[] = $rol_filter;
    }

    if ($activo_filter !== '') {
        $where[] = "u.activo = ?";
        $params[] = (int)$activo_filter;
    }

    if ($search) {
        $where[] = "(u.nombres LIKE ? OR u.apellidos LIKE ? OR u.dni LIKE ? OR u.username LIKE ?)";
        $s = "%$search%";
        array_push($params, $s, $s, $s, $s);
    }

    $where_sql = implode(' AND ', $where);

    $stmt = $pdo->prepare("
        SELECT u.id, u.nombres, u.apellidos, u.dni, u.telefono, u.email,
               u.username, u.activo, u.fecha_registro, u.fecha_baja,
               r.nombre as rol, r.id as rol_id,
               tc.fecha_inicio_cobro
        FROM usuarios u
        JOIN roles r ON u.rol_id = r.id
        LEFT JOIN trabajador_config tc ON u.id = tc.usuario_id
        WHERE $where_sql
        ORDER BY r.id ASC, u.apellidos ASC, u.nombres ASC
    ");
    $stmt->execute($params);
    $usuarios = $stmt->fetchAll();

    // Cast types
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
        SELECT u.id, u.nombres, u.apellidos, u.dni, u.telefono, u.email,
               u.username, u.activo, u.fecha_registro, u.fecha_baja,
               r.nombre as rol, r.id as rol_id,
               tc.fecha_inicio_cobro, tc.notas as config_notas
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

    $errors = validate_required($data, ['nombres', 'apellidos', 'dni', 'username', 'password', 'rol_id']);
    if (!empty($errors)) error_response('Datos incompletos', 400, $errors);

    // Validar DNI 8 dígitos
    if (!preg_match('/^\d{8}$/', $data['dni'])) {
        error_response('El DNI debe tener exactamente 8 dígitos');
    }

    // Validar password
    if (strlen($data['password']) < 6) {
        error_response('La contraseña debe tener al menos 6 caracteres');
    }

    // Validar rol válido
    $rol_id = (int)$data['rol_id'];
    if (!in_array($rol_id, [1, 2, 3])) {
        error_response('Rol no válido');
    }

    $pdo = db();

    // Verificar DNI duplicado
    $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE dni = ?");
    $stmt->execute([$data['dni']]);
    if ($stmt->fetch()) error_response('Ya existe un usuario con ese DNI');

    // Verificar username duplicado
    $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE username = ?");
    $stmt->execute([$data['username']]);
    if ($stmt->fetch()) error_response('El nombre de usuario ya está en uso');

    $pdo->beginTransaction();
    try {
        $password_hash = password_hash($data['password'], PASSWORD_BCRYPT, ['cost' => 12]);

        $stmt = $pdo->prepare("
            INSERT INTO usuarios (rol_id, nombres, apellidos, dni, telefono, email, username, password_hash, registrado_por)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $rol_id,
            sanitize_string($data['nombres']),
            sanitize_string($data['apellidos']),
            $data['dni'],
            sanitize_string($data['telefono'] ?? ''),
            sanitize_string($data['email'] ?? ''),
            sanitize_string($data['username']),
            $password_hash,
            $admin['id']
        ]);

        $new_id = $pdo->lastInsertId();

        // Si es trabajador, crear configuración de puesta en marcha
        if ($rol_id == 3 && !empty($data['fecha_inicio_cobro'])) {
            $stmt2 = $pdo->prepare("
                INSERT INTO trabajador_config (usuario_id, fecha_inicio_cobro, notas, configurado_por)
                VALUES (?, ?, ?, ?)
            ");
            $stmt2->execute([
                $new_id,
                $data['fecha_inicio_cobro'],
                sanitize_string($data['notas_config'] ?? ''),
                $admin['id']
            ]);
        }

        $pdo->commit();

        registrar_auditoria($admin['id'], 'CREAR_USUARIO', 'usuarios', $new_id, null, [
            'nombres' => $data['nombres'],
            'rol_id' => $rol_id,
            'username' => $data['username']
        ]);

        success_response(['id' => (int)$new_id], 'Usuario creado correctamente', 201);

    } catch (Exception $e) {
        $pdo->rollBack();
        error_response('Error al crear usuario: ' . ($e->getMessage()), 500);
    }
}

function handle_update() {
    $admin = Auth::requireRole('admin');
    $data = get_json_input();

    if (empty($data['id'])) error_response('ID de usuario requerido');

    $id = (int)$data['id'];
    $pdo = db();

    // Obtener datos actuales
    $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE id = ?");
    $stmt->execute([$id]);
    $current = $stmt->fetch();
    if (!$current) error_response('Usuario no encontrado', 404);

    $pdo->beginTransaction();
    try {
        $fields = [];
        $params = [];

        $updatable = ['nombres', 'apellidos', 'dni', 'telefono', 'email', 'username', 'rol_id'];
        foreach ($updatable as $field) {
            if (isset($data[$field])) {
                $fields[] = "$field = ?";
                $params[] = $field === 'rol_id' ? (int)$data[$field] : sanitize_string($data[$field]);
            }
        }

        if (!empty($fields)) {
            $params[] = $id;
            $sql = "UPDATE usuarios SET " . implode(', ', $fields) . " WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
        }

        // Actualizar fecha de inicio de cobro si se proporciona
        if (isset($data['fecha_inicio_cobro'])) {
            $stmt2 = $pdo->prepare("
                INSERT INTO trabajador_config (usuario_id, fecha_inicio_cobro, notas, configurado_por)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                fecha_inicio_cobro = VALUES(fecha_inicio_cobro),
                notas = VALUES(notas),
                configurado_por = VALUES(configurado_por),
                fecha_configuracion = NOW()
            ");
            $stmt2->execute([
                $id,
                $data['fecha_inicio_cobro'],
                sanitize_string($data['notas_config'] ?? ''),
                $admin['id']
            ]);
        }

        $pdo->commit();

        registrar_auditoria($admin['id'], 'EDITAR_USUARIO', 'usuarios', $id, $current, $data);

        success_response(null, 'Usuario actualizado correctamente');

    } catch (Exception $e) {
        $pdo->rollBack();
        error_response('Error al actualizar: ' . $e->getMessage(), 500);
    }
}

function handle_toggle() {
    $admin = Auth::requireRole('admin');
    $data = get_json_input();

    if (empty($data['id'])) error_response('ID requerido');

    $id = (int)$data['id'];
    if ($id === (int)$admin['id']) error_response('No puede desactivarse a sí mismo');

    $pdo = db();
    $stmt = $pdo->prepare("SELECT id, activo, nombres FROM usuarios WHERE id = ?");
    $stmt->execute([$id]);
    $user = $stmt->fetch();
    if (!$user) error_response('Usuario no encontrado', 404);

    $new_status = $user['activo'] ? 0 : 1;
    $fecha_baja = $new_status ? null : date('Y-m-d H:i:s');

    $stmt2 = $pdo->prepare("UPDATE usuarios SET activo = ?, fecha_baja = ? WHERE id = ?");
    $stmt2->execute([$new_status, $fecha_baja, $id]);

    // Invalidar sesiones si se desactiva
    if (!$new_status) {
        $stmt3 = $pdo->prepare("UPDATE sesiones SET activo = 0 WHERE usuario_id = ?");
        $stmt3->execute([$id]);
    }

    $action_text = $new_status ? 'ACTIVAR_USUARIO' : 'DESACTIVAR_USUARIO';
    registrar_auditoria($admin['id'], $action_text, 'usuarios', $id);

    success_response(
        ['activo' => (bool)$new_status],
        $new_status ? 'Usuario activado' : 'Usuario dado de baja'
    );
}

function handle_reset_password() {
    $admin = Auth::requireRole('admin');
    $data = get_json_input();

    if (empty($data['id']) || empty($data['password'])) {
        error_response('ID y nueva contraseña requeridos');
    }

    if (strlen($data['password']) < 6) {
        error_response('La contraseña debe tener al menos 6 caracteres');
    }

    $pdo = db();
    $hash = password_hash($data['password'], PASSWORD_BCRYPT, ['cost' => 12]);
    $stmt = $pdo->prepare("UPDATE usuarios SET password_hash = ? WHERE id = ?");
    $stmt->execute([$hash, (int)$data['id']]);

    registrar_auditoria($admin['id'], 'RESET_PASSWORD', 'usuarios', (int)$data['id']);

    success_response(null, 'Contraseña restablecida correctamente');
}
