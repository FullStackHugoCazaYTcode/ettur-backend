<?php
/**
 * ETTUR - Helpers v2.0
 */

function cors_headers() {
    // Permitir CORS desde cualquier origen
    $origin = '*';
    if (defined('CORS_ORIGIN') && CORS_ORIGIN !== '*') {
        $origin = CORS_ORIGIN;
    }
    
    header("Access-Control-Allow-Origin: $origin");
    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS, PATCH");
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, Origin");
    header("Access-Control-Allow-Credentials: true");
    header("Access-Control-Max-Age: 86400");

    // Responder inmediatamente a preflight OPTIONS
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        header("Content-Length: 0");
        exit;
    }
}

function json_response($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function error_response($message, $code = 400, $errors = []) {
    $response = ['success' => false, 'message' => $message];
    if (!empty($errors)) {
        $response['errors'] = $errors;
    }
    json_response($response, $code);
}

function success_response($data = null, $message = 'OK', $code = 200) {
    $response = ['success' => true, 'message' => $message];
    if ($data !== null) {
        $response['data'] = $data;
    }
    json_response($response, $code);
}

function get_json_input() {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        // Si no es JSON, puede ser form-data - devolver array vacío
        return [];
    }
    return $data ?? [];
}

function get_client_ip() {
    $headers = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];
    foreach ($headers as $header) {
        if (!empty($_SERVER[$header])) {
            $ip = explode(',', $_SERVER[$header])[0];
            return trim($ip);
        }
    }
    return '0.0.0.0';
}

function registrar_auditoria($usuario_id, $accion, $tabla = null, $registro_id = null, $datos_ant = null, $datos_new = null) {
    try {
        $pdo = db();
        $stmt = $pdo->prepare("INSERT INTO auditoria (usuario_id, accion, tabla_afectada, registro_id, datos_anteriores, datos_nuevos, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $usuario_id,
            $accion,
            $tabla,
            $registro_id,
            $datos_ant ? json_encode($datos_ant) : null,
            $datos_new ? json_encode($datos_new) : null,
            get_client_ip()
        ]);
    } catch (Exception $e) {
        // Silent fail
    }
}

function sanitize_string($str) {
    return htmlspecialchars(strip_tags(trim($str)), ENT_QUOTES, 'UTF-8');
}

function validate_required($data, $fields) {
    $errors = [];
    foreach ($fields as $field) {
        if (!isset($data[$field]) || (is_string($data[$field]) && trim($data[$field]) === '')) {
            $errors[] = "El campo '$field' es obligatorio";
        }
    }
    return $errors;
}
