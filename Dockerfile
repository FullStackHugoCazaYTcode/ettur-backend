FROM php:8.2-cli

# Instalar extensión MySQL
RUN docker-php-ext-install pdo pdo_mysql

# Configurar PHP
RUN echo "upload_max_filesize = 10M" > /usr/local/etc/php/conf.d/custom.ini && \
    echo "post_max_size = 12M" >> /usr/local/etc/php/conf.d/custom.ini && \
    echo "display_errors = Off" >> /usr/local/etc/php/conf.d/custom.ini && \
    echo "date.timezone = America/Lima" >> /usr/local/etc/php/conf.d/custom.ini

# Copiar proyecto
WORKDIR /var/www/html
COPY . /var/www/html/

# Crear carpeta uploads
RUN mkdir -p /var/www/html/uploads && \
    chmod -R 777 /var/www/html/uploads

# Router PHP - ahora permite acceso directo a archivos .php que existen
RUN printf '<?php\n\
$uri = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);\n\
$file = __DIR__ . $uri;\n\
// Si el archivo existe, servirlo directamente\n\
if ($uri !== "/" && file_exists($file) && is_file($file)) {\n\
    $ext = pathinfo($file, PATHINFO_EXTENSION);\n\
    // Si es PHP, ejecutarlo\n\
    if ($ext === "php") {\n\
        require $file;\n\
        return true;\n\
    }\n\
    // Archivos estáticos\n\
    $mimes = ["jpg"=>"image/jpeg","jpeg"=>"image/jpeg","png"=>"image/png","webp"=>"image/webp","css"=>"text/css","js"=>"application/javascript","json"=>"application/json"];\n\
    if (isset($mimes[$ext])) header("Content-Type: " . $mimes[$ext]);\n\
    readfile($file);\n\
    return true;\n\
}\n\
// Todo lo demás va al index.php\n\
require __DIR__ . "/index.php";\n' > /var/www/html/router.php

# Script de inicio
RUN printf '#!/bin/bash\necho "=== ETTUR Backend ==="\necho "Starting on port: ${PORT:-8080}"\nexec php -S 0.0.0.0:${PORT:-8080} /var/www/html/router.php\n' > /usr/local/bin/start.sh && \
    chmod +x /usr/local/bin/start.sh

CMD ["/usr/local/bin/start.sh"]
