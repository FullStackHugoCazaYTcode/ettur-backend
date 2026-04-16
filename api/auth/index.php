<?php
/**
 * ETTUR - API de Autenticación v2.0
 * Login con DNI + Placa
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
    $errors = validate_required($data, ['dni', 'placa']);
    if (!empty($errors)) error_response('Ingrese DNI y Placa', 400, $errors);

    $dni = sanitize_string($data['dni']);
    $placa = strtoupper(sanitize_string($data['placa']));

    $pdo = db();
    $stmt = $pdo->prepare("
        SELECT u.id, u.rol_id, u.nombres, u.apellidos, u.dni, u.placa,
               u.tipo_trabajador, u.monto_personalizado, u.frecuencia_personalizado,
               u.activo, u.telefono, u.email, u.username,
               r.nombre as rol_nombre
        FROM usuarios u 
        JOIN roles r ON u.rol_id = r.id 
        WHERE u.dni = ? AND UPPER(u.placa) = ?
    ");
    $stmt->execute([$dni, $placa]);
    $user = $stmt->fetch();

    if (!$user) {
        registrar_auditoria(null, 'LOGIN_FALLIDO', 'usuarios', null, ['dni' => $dni, 'placa' => $placa]);
        error_response('DNI o Placa incorrectos', 401);
    }

    if (!$user['activo']) {
        error_response('Su cuenta ha sido desactivada. Contacte al administrador.', 403);
    }

    $token = Auth::generateToken([
        'user_id' => $user['id'],
        'rol' => $user['rol_nombre'],
        'username' => $user['username'] ?? $user['dni']
    ]);

    registrar_auditoria($user['id'], 'LOGIN_EXITOSO', 'usuarios', $user['id']);

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
            'placa' => $user['placa'],
            'telefono' => $user['telefono'],
            'email' => $user['email'],
            'username' => $user['username'],
            'rol' => $user['rol_nombre'],
            'tipo_trabajador' => $user['tipo_trabajador'],
            'monto_personalizado' => $user['monto_personalizado'],
            'frecuencia_personalizado' => $user['frecuencia_personalizado'],
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
    $extra = [];

    // Obtener datos completos del usuario
    $stmt = $pdo->prepare("SELECT tipo_trabajador, monto_personalizado, frecuencia_personalizado, placa FROM usuarios WHERE id = ?");
    $stmt->execute([$user['id']]);
    $userData = $stmt->fetch();
    $extra['placa'] = $userData['placa'] ?? '';
    $extra['tipo_trabajador'] = $userData['tipo_trabajador'];

    if ($user['rol_nombre'] === 'trabajador') {
        $stmt = $pdo->prepare("SELECT fecha_inicio_cobro FROM trabajador_config WHERE usuario_id = ?");
        $stmt->execute([$user['id']]);
        $config = $stmt->fetch();
        $extra['fecha_inicio_cobro'] = $config ? $config['fecha_inicio_cobro'] : null;

        $stmt2 = $pdo->prepare("SELECT COUNT(*) as pendientes FROM pagos WHERE trabajador_id = ? AND estado = 'pendiente'");
        $stmt2->execute([$user['id']]);
        $extra['pagos_pendientes'] = (int)$stmt2->fetch()['pendientes'];
    }

    if (in_array($user['rol_nombre'], ['admin', 'coadmin'])) {
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
    // Cambiar placa (en vez de password)
    $user = Auth::requireAuth();
    $data = get_json_input();
    
    if (empty($data['placa_nueva'])) {
        error_response('Ingrese la nueva placa');
    }

    $placa_nueva = strtoupper(sanitize_string($data['placa_nueva']));

    $pdo = db();
    // Verificar que la placa no esté en uso
    $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE UPPER(placa) = ? AND id != ?");
    $stmt->execute([$placa_nueva, $user['id']]);
    if ($stmt->fetch()) {
        error_response('Esa placa ya está asignada a otro usuario');
    }

    $stmt2 = $pdo->prepare("UPDATE usuarios SET placa = ? WHERE id = ?");
    $stmt2->execute([$placa_nueva, $user['id']]);

    registrar_auditoria($user['id'], 'CAMBIO_PLACA', 'usuarios', $user['id']);
    success_response(null, 'Placa actualizada correctamente');
}
