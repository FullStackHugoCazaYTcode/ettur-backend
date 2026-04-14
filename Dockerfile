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

# Router PHP para manejar las rutas (reemplaza .htaccess)
RUN printf '<?php\n\
$uri = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);\n\
$file = __DIR__ . $uri;\n\
// Servir archivos estáticos si existen\n\
if ($uri !== "/" && file_exists($file) && is_file($file)) {\n\
    $ext = pathinfo($file, PATHINFO_EXTENSION);\n\
    $mimes = ["jpg"=>"image/jpeg","jpeg"=>"image/jpeg","png"=>"image/png","webp"=>"image/webp","css"=>"text/css","js"=>"application/javascript","json"=>"application/json"];\n\
    if (isset($mimes[$ext])) header("Content-Type: " . $mimes[$ext]);\n\
    readfile($file);\n\
    return true;\n\
}\n\
// Todo lo demás va al index.php\n\
require __DIR__ . "/index.php";\n' > /var/www/html/router.php

EXPOSE ${PORT}

CMD php -S 0.0.0.0:${PORT:-80} /var/www/html/router.php
