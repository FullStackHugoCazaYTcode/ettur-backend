<?php
$uri = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
$file = __DIR__ . $uri;

// Archivos PHP existentes - ejecutar directamente
if ($uri !== "/" && is_file($file) && pathinfo($file, PATHINFO_EXTENSION) === "php") {
    require $file;
    return true;
}

// Archivos estáticos existentes - dejar que PHP built-in los sirva
if ($uri !== "/" && is_file($file)) {
    return false;
}

// Todo lo demás al index.php
require __DIR__ . "/index.php";
