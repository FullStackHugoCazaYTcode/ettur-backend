<?php
/**
 * ETTUR - Middleware de Autenticación JWT
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

class Auth {

    /**
     * Genera un token JWT simple
     */
    public static function generateToken($payload) {
        $header = base64_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload['iat'] = time();
        $payload['exp'] = time() + JWT_EXPIRY;
        $payload_encoded = base64_encode(json_encode($payload));
        $signature = hash_hmac('sha256', "$header.$payload_encoded", JWT_SECRET);
        $token = "$header.$payload_encoded.$signature";

        // Guardar sesión en BD
        try {
            $pdo = db();
            $stmt = $pdo->prepare("INSERT INTO sesiones (usuario_id, token, ip_address, user_agent, fecha_expiracion) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([
                $payload['user_id'],
                $token,
                get_client_ip(),
                $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
                date('Y-m-d H:i:s', $payload['exp'])
            ]);
        } catch (Exception $e) {
            // Continue even if session save fails
        }

        return $token;
    }

    /**
     * Decodifica y valida un token JWT
     */
    public static function decodeToken($token) {
        $parts = explode('.', $token);
        if (count($parts) !== 3) return null;

        list($header, $payload, $signature) = $parts;

        // Verificar firma
        $valid_signature = hash_hmac('sha256', "$header.$payload", JWT_SECRET);
        if (!hash_equals($valid_signature, $signature)) return null;

        $data = json_decode(base64_decode($payload), true);
        if (!$data) return null;

        // Verificar expiración
        if (isset($data['exp']) && $data['exp'] < time()) return null;

        // Verificar sesión activa en BD
        try {
            $pdo = db();
            $stmt = $pdo->prepare("SELECT id FROM sesiones WHERE token = ? AND activo = 1 AND fecha_expiracion > NOW()");
            $stmt->execute([$token]);
            if (!$stmt->fetch()) return null;
        } catch (Exception $e) {
            // If DB check fails, rely on token validity
        }

        return $data;
    }

    /**
     * Middleware: requiere autenticación
     */
    public static function requireAuth() {
        $headers = getallheaders();
        $auth_header = $headers['Authorization'] ?? $headers['authorization'] ?? '';

        if (empty($auth_header) || !preg_match('/Bearer\s+(.+)/', $auth_header, $matches)) {
            error_response('Token de autenticación requerido', 401);
        }

        $token = $matches[1];
        $payload = self::decodeToken($token);

        if (!$payload) {
            error_response('Token inválido o expirado', 401);
        }

        // Verificar que el usuario sigue activo
        $pdo = db();
        $stmt = $pdo->prepare("SELECT u.id, u.rol_id, u.nombres, u.apellidos, u.username, u.activo, r.nombre as rol_nombre FROM usuarios u JOIN roles r ON u.rol_id = r.id WHERE u.id = ?");
        $stmt->execute([$payload['user_id']]);
        $user = $stmt->fetch();

        if (!$user || !$user['activo']) {
            error_response('Usuario inactivo o no encontrado', 403);
        }

        return $user;
    }

    /**
     * Middleware: requiere rol específico
     */
    public static function requireRole($roles) {
        $user = self::requireAuth();
        if (!is_array($roles)) $roles = [$roles];

        if (!in_array($user['rol_nombre'], $roles)) {
            error_response('No tiene permisos para esta acción', 403);
        }

        return $user;
    }

    /**
     * Cerrar sesión
     */
    public static function logout($token) {
        try {
            $pdo = db();
            $stmt = $pdo->prepare("UPDATE sesiones SET activo = 0 WHERE token = ?");
            $stmt->execute([$token]);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
}
