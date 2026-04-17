<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: text/html; charset=utf-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}
?>
<!DOCTYPE html>
<html>
<head><title>Test POST Pago</title></head>
<body style="font-family:Arial;max-width:600px;margin:20px auto;padding:20px">
<h2>Test de envío de pago</h2>

<?php if ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
    <h3>Resultado del POST:</h3>
    <pre style="background:#f0f0f0;padding:10px;overflow:auto">
POST: <?= print_r($_POST, true) ?>
FILES: <?= print_r($_FILES, true) ?>
    </pre>
    
    <?php
    // Intentar ejecutar el mismo código que pagos
    try {
        require_once __DIR__ . '/config/database.php';
        require_once __DIR__ . '/config/helpers.php';
        require_once __DIR__ . '/middleware/Auth.php';
        
        echo "<p style='color:green'>✅ Archivos cargados OK</p>";
        
        // Verificar token
        $headers = getallheaders();
        $auth = $headers['Authorization'] ?? $headers['authorization'] ?? 'NO AUTH HEADER';
        echo "<p>Auth header: " . htmlspecialchars(substr($auth, 0, 50)) . "...</p>";
        
        // Test DB
        $pdo = db();
        echo "<p style='color:green'>✅ BD conectada</p>";
        
        // Verificar que los campos llegan
        $fecha_inicio = $_POST['fecha_inicio'] ?? 'NO RECIBIDO';
        $fecha_fin = $_POST['fecha_fin'] ?? 'NO RECIBIDO';
        $metodo = $_POST['metodo_pago'] ?? 'NO RECIBIDO';
        
        echo "<p>fecha_inicio: <strong>$fecha_inicio</strong></p>";
        echo "<p>fecha_fin: <strong>$fecha_fin</strong></p>";
        echo "<p>metodo_pago: <strong>$metodo</strong></p>";
        
        if (isset($_FILES['comprobante'])) {
            echo "<p style='color:green'>✅ Archivo recibido: " . $_FILES['comprobante']['name'] . " (" . $_FILES['comprobante']['size'] . " bytes)</p>";
            echo "<p>Error code: " . $_FILES['comprobante']['error'] . "</p>";
        } else {
            echo "<p style='color:red'>❌ No se recibió archivo</p>";
        }
        
    } catch (Throwable $e) {
        echo "<p style='color:red'>❌ ERROR: " . $e->getMessage() . "</p>";
        echo "<p>Archivo: " . $e->getFile() . ":" . $e->getLine() . "</p>";
    }
    ?>
    <hr>
<?php endif; ?>

<h3>Formulario de prueba:</h3>
<form method="POST" enctype="multipart/form-data">
    <p>
        <label>fecha_inicio:</label><br>
        <input type="text" name="fecha_inicio" value="2026-04-06" style="width:100%;padding:5px">
    </p>
    <p>
        <label>fecha_fin:</label><br>
        <input type="text" name="fecha_fin" value="2026-04-12" style="width:100%;padding:5px">
    </p>
    <p>
        <label>metodo_pago:</label><br>
        <input type="text" name="metodo_pago" value="yape" style="width:100%;padding:5px">
    </p>
    <p>
        <label>comprobante (imagen):</label><br>
        <input type="file" name="comprobante" accept="image/*">
    </p>
    <p>
        <button type="submit" style="padding:10px 20px;background:#1a3a5c;color:white;border:none;cursor:pointer">
            Enviar POST de prueba
        </button>
    </p>
</form>

</body>
</html>
