<?php
/**
 * ETTUR - API de Autenticación
 * Endpoints: POST /login, POST /logout, GET /me
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helpers.php';
require_once __DIR__ . '/../../middleware/Auth.php';

cors_headers();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'login':
        if ($method !== 'POST') error_response('Método no permitido', 405);
        handle_login();
        break;

    case 'logout':
        if ($method !== 'POST') error_response('Método no permitido', 405);
        handle_logout();
        break;

    case 'me':
        if ($method !== 'GET') error_response('Método no permitido', 405);
        handle_me();
        break;

    case 'change-password':
        if ($method !== 'POST') error_response('Método no permitido', 405);
        handle_change_password();
        break;

    default:
        error_response('Acción no válida', 404);
}

function handle_login() {
    $data = get_json_input();
    $errors = validate_required($data, ['username', 'password']);
    if (!empty($errors)) error_response('Datos incompletos', 400, $errors);

    $username = sanitize_string($data['username']);
    $password = $data['password'];

    $pdo = db();
    $stmt = $pdo->prepare("
        SELECT u.id, u.rol_id, u.nombres, u.apellidos, u.dni, u.username, 
               u.password_hash, u.activo, u.telefono, u.email,
               r.nombre as rol_nombre
        FROM usuarios u 
        JOIN roles r ON u.rol_id = r.id 
        WHERE u.username = ?
    ");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        registrar_auditoria(null, 'LOGIN_FALLIDO', 'usuarios', null, ['username' => $username]);
        error_response('Credenciales incorrectas', 401);
    }

    if (!$user['activo']) {
        error_response('Su cuenta ha sido desactivada. Contacte al administrador.', 403);
    }

    // Generar token
    $token = Auth::generateToken([
        'user_id' => $user['id'],
        'rol' => $user['rol_nombre'],
        'username' => $user['username']
    ]);

    registrar_auditoria($user['id'], 'LOGIN_EXITOSO', 'usuarios', $user['id']);

    // Obtener fecha de inicio de cobro si es trabajador
    $fecha_inicio_cobro = null;
    if ($user['rol_nombre'] === 'trabajador') {
        $stmt2 = $pdo->prepare("SELECT fecha_inicio_cobro FROM trabajador_config WHERE usuario_id = ?");
        $stmt2->execute([$user['id']]);
        $config = $stmt2->fetch();
        $fecha_inicio_cobro = $config ? $config['fecha_inicio_cobro'] : null;
    }

    success_response([
        'token' => $token,
        'user' => [
            'id' => (int)$user['id'],
            'nombres' => $user['nombres'],
            'apellidos' => $user['apellidos'],
            'dni' => $user['dni'],
            'telefono' => $user['telefono'],
            'email' => $user['email'],
            'username' => $user['username'],
            'rol' => $user['rol_nombre'],
            'fecha_inicio_cobro' => $fecha_inicio_cobro
        ]
    ], 'Inicio de sesión exitoso');
}

function handle_logout() {
    $headers = getallheaders();
    $auth_header = $headers['Authorization'] ?? $headers['authorization'] ?? '';

    if (preg_match('/Bearer\s+(.+)/', $auth_header, $matches)) {
        Auth::logout($matches[1]);
    }

    success_response(null, 'Sesión cerrada correctamente');
}

function handle_me() {
    $user = Auth::requireAuth();

    $pdo = db();

    // Datos extras según rol
    $extra = [];

    if ($user['rol_nombre'] === 'trabajador') {
        // Obtener deuda pendiente
        $stmt = $pdo->prepare("
            SELECT tc.fecha_inicio_cobro FROM trabajador_config tc WHERE tc.usuario_id = ?
        ");
        $stmt->execute([$user['id']]);
        $config = $stmt->fetch();
        $extra['fecha_inicio_cobro'] = $config ? $config['fecha_inicio_cobro'] : null;

        // Contar pagos pendientes
        $stmt2 = $pdo->prepare("SELECT COUNT(*) as pendientes FROM pagos WHERE trabajador_id = ? AND estado = 'pendiente'");
        $stmt2->execute([$user['id']]);
        $extra['pagos_pendientes'] = (int)$stmt2->fetch()['pendientes'];
    }

    if (in_array($user['rol_nombre'], ['admin', 'coadmin'])) {
        // Contar pagos por validar
        $stmt3 = $pdo->prepare("SELECT COUNT(*) as por_validar FROM pagos WHERE estado = 'pendiente'");
        $stmt3->execute();
        $extra['pagos_por_validar'] = (int)$stmt3->fetch()['por_validar'];
    }

    success_response(array_merge([
        'id' => (int)$user['id'],
        'nombres' => $user['nombres'],
        'apellidos' => $user['apellidos'],
        'username' => $user['username'],
        'rol' => $user['rol_nombre']
    ], $extra));
}

function handle_change_password() {
    $user = Auth::requireAuth();
    $data = get_json_input();
    $errors = validate_required($data, ['password_actual', 'password_nuevo']);
    if (!empty($errors)) error_response('Datos incompletos', 400, $errors);

    if (strlen($data['password_nuevo']) < 6) {
        error_response('La nueva contraseña debe tener al menos 6 caracteres');
    }

    $pdo = db();
    $stmt = $pdo->prepare("SELECT password_hash FROM usuarios WHERE id = ?");
    $stmt->execute([$user['id']]);
    $current = $stmt->fetch();

    if (!password_verify($data['password_actual'], $current['password_hash'])) {
        error_response('La contraseña actual es incorrecta');
    }

    $new_hash = password_hash($data['password_nuevo'], PASSWORD_BCRYPT, ['cost' => 12]);
    $stmt2 = $pdo->prepare("UPDATE usuarios SET password_hash = ? WHERE id = ?");
    $stmt2->execute([$new_hash, $user['id']]);

    registrar_auditoria($user['id'], 'CAMBIO_PASSWORD', 'usuarios', $user['id']);

    success_response(null, 'Contraseña actualizada correctamente');
}
