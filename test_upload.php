<?php
/**
 * Test de upload - ELIMINAR DESPUÉS
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Diagnostico completo
$info = [
    'php_version' => PHP_VERSION,
    'method' => $_SERVER['REQUEST_METHOD'],
    'content_type' => $_SERVER['CONTENT_TYPE'] ?? 'not set',
    'upload_max_filesize' => ini_get('upload_max_filesize'),
    'post_max_size' => ini_get('post_max_size'),
    'file_uploads' => ini_get('file_uploads'),
    'upload_tmp_dir' => ini_get('upload_tmp_dir') ?: sys_get_temp_dir(),
    'tmp_writable' => is_writable(sys_get_temp_dir()),
    'uploads_dir_exists' => is_dir(__DIR__ . '/uploads'),
    'uploads_dir_writable' => is_writable(__DIR__ . '/uploads'),
    'post_data' => $_POST,
    'files_data' => [],
    'errors' => []
];

// Check FILES
if (!empty($_FILES)) {
    foreach ($_FILES as $key => $file) {
        $info['files_data'][$key] = [
            'name' => $file['name'],
            'type' => $file['type'],
            'size' => $file['size'],
            'error' => $file['error'],
            'error_msg' => [
                0 => 'OK',
                1 => 'Excede upload_max_filesize',
                2 => 'Excede MAX_FILE_SIZE del form',
                3 => 'Subida parcial',
                4 => 'No se subió archivo',
                6 => 'No hay directorio temporal',
                7 => 'Error de escritura en disco'
            ][$file['error']] ?? 'Error desconocido',
            'tmp_name_exists' => file_exists($file['tmp_name'])
        ];
    }
} else {
    $info['errors'][] = 'No se recibieron archivos ($_FILES vacío)';
}

// Test de escritura
try {
    $testFile = __DIR__ . '/uploads/test_' . time() . '.txt';
    $written = file_put_contents($testFile, 'test');
    $info['write_test'] = $written !== false ? 'OK' : 'FAILED';
    if (file_exists($testFile)) unlink($testFile);
} catch (Exception $e) {
    $info['write_test'] = 'ERROR: ' . $e->getMessage();
}

// Test require de archivos
try {
    require_once __DIR__ . '/config/database.php';
    $info['database_config'] = 'OK';
    
    $pdo = db();
    $info['database_connection'] = 'OK';
} catch (Throwable $e) {
    $info['database_error'] = $e->getMessage();
}

// Test require helpers
try {
    if (!function_exists('cors_headers')) {
        require_once __DIR__ . '/config/helpers.php';
    }
    $info['helpers'] = 'OK';
} catch (Throwable $e) {
    $info['helpers_error'] = $e->getMessage();
}

// Test require Auth
try {
    if (!class_exists('Auth')) {
        require_once __DIR__ . '/middleware/Auth.php';
    }
    $info['auth'] = 'OK';
} catch (Throwable $e) {
    $info['auth_error'] = $e->getMessage();
}

echo json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
