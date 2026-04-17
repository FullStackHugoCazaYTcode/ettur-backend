<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

$result = [];

// Test 1: Verificar que el archivo existe
$file = __DIR__ . '/api/pagos/index.php';
$result['file_exists'] = file_exists($file);
$result['file_size'] = file_exists($file) ? filesize($file) . ' bytes' : 'N/A';

// Test 2: Verificar sintaxis del archivo
$output = [];
$exitCode = 0;
exec("php -l " . escapeshellarg($file) . " 2>&1", $output, $exitCode);
$result['syntax_check'] = implode("\n", $output);
$result['syntax_ok'] = $exitCode === 0;

// Test 3: Contar funciones definidas
if (file_exists($file)) {
    $content = file_get_contents($file);
    $result['has_handle_registrar'] = strpos($content, 'function handle_registrar_pago') !== false;
    $result['has_handle_periodos'] = strpos($content, 'function handle_periodos_pendientes') !== false;
    $result['has_handle_validar'] = strpos($content, 'function handle_validar_pago') !== false;
    $result['has_generar_semanales'] = strpos($content, 'function generar_periodos_semanales') !== false;
    $result['has_ensure_periodo'] = strpos($content, 'function ensure_periodo') !== false;
    $result['total_lines'] = substr_count($content, "\n") + 1;
}

// Test 4: Intentar incluir los archivos base
try {
    require_once __DIR__ . '/config/database.php';
    require_once __DIR__ . '/config/helpers.php';
    require_once __DIR__ . '/middleware/Auth.php';
    $result['dependencies'] = 'OK';
} catch (Throwable $e) {
    $result['dependencies_error'] = $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine();
}

echo json_encode($result, JSON_PRETTY_PRINT);
