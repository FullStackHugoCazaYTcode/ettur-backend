<?php
/**
 * ETTUR - Script temporal para resetear contraseña del admin
 * ELIMINAR DESPUÉS DE USAR
 * 
 * Uso: https://tu-backend.railway.app/reset_admin.php
 */

require_once __DIR__ . '/config/database.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

try {
    $pdo = db();
    
    // Generar hash correcto para "Admin2025!"
    $password = 'Admin2025!';
    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    
    // Actualizar contraseña del admin
    $stmt = $pdo->prepare("UPDATE usuarios SET password_hash = ? WHERE username = 'admin'");
    $stmt->execute([$hash]);
    
    $affected = $stmt->rowCount();
    
    if ($affected > 0) {
        echo json_encode([
            'success' => true,
            'message' => 'Contraseña del admin reseteada correctamente',
            'usuario' => 'admin',
            'password' => $password,
            'nota' => 'ELIMINA ESTE ARCHIVO (reset_admin.php) DE TU REPOSITORIO INMEDIATAMENTE'
        ], JSON_PRETTY_PRINT);
    } else {
        // Si no existe el admin, crearlo
        $stmt2 = $pdo->prepare("SELECT id FROM usuarios WHERE username = 'admin'");
        $stmt2->execute();
        
        if (!$stmt2->fetch()) {
            echo json_encode([
                'success' => false,
                'message' => 'No se encontró el usuario admin. ¿Ejecutaste el schema.sql en MySQL Workbench?'
            ]);
        } else {
            echo json_encode([
                'success' => true,
                'message' => 'El usuario admin existe pero no se actualizó (ya tenía la contraseña correcta)'
            ]);
        }
    }
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
