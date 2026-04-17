FROM php:8.2-cli

# Instalar extensión MySQL
RUN docker-php-ext-install pdo pdo_mysql

# Configurar PHP
RUN echo "upload_max_filesize = 10M" > /usr/local/etc/php/conf.d/custom.ini && \
    echo "post_max_size = 12M" >> /usr/local/etc/php/conf.d/custom.ini && \
    echo "max_execution_time = 120" >> /usr/local/etc/php/conf.d/custom.ini && \
    echo "max_input_time = 120" >> /usr/local/etc/php/conf.d/custom.ini && \
    echo "memory_limit = 128M" >> /usr/local/etc/php/conf.d/custom.ini && \
    echo "file_uploads = On" >> /usr/local/etc/php/conf.d/custom.ini && \
    echo "display_errors = Off" >> /usr/local/etc/php/conf.d/custom.ini && \
    echo "date.timezone = America/Lima" >> /usr/local/etc/php/conf.d/custom.ini

# Copiar proyecto
WORKDIR /var/www/html
COPY . /var/www/html/

# Crear carpeta uploads
RUN mkdir -p /var/www/html/uploads && \
    chmod -R 777 /var/www/html/uploads

# Router PHP mejorado - NO interfiere con POST/uploads
RUN printf '<?php\n\
$uri = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);\n\
$file = __DIR__ . $uri;\n\
// Archivos PHP existentes - ejecutar directamente\n\
if ($uri !== "/" && is_file($file) && pathinfo($file, PATHINFO_EXTENSION) === "php") {\n\
    require $file;\n\
    return true;\n\
}\n\
// Archivos estáticos existentes\n\
if ($uri !== "/" && is_file($file)) {\n\
    return false; // PHP built-in server sirve el archivo\n\
}\n\
// Todo lo demás al index.php\n\
require __DIR__ . "/index.php";\n' > /var/www/html/router.php

# Script de inicio
RUN printf '#!/bin/bash\necho "=== ETTUR Backend v2.0 ==="\necho "Port: ${PORT:-8080}"\nexec php -S 0.0.0.0:${PORT:-8080} /var/www/html/router.php\n' > /usr/local/bin/start.sh && \
    chmod +x /usr/local/bin/start.sh

CMD ["/usr/local/bin/start.sh"]
