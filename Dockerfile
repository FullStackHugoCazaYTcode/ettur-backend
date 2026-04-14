FROM php:8.2-fpm-alpine

# 1. Instalar extensiones de base de datos
RUN docker-php-ext-install pdo pdo_mysql
RUN apk add --no-cache nginx

# 2. Crear carpeta necesaria para que Nginx arranque
RUN mkdir -p /run/nginx

# 3. Configurar Nginx (Solo lo necesario)
RUN echo 'server { \
    listen 80; \
    listen [::]:80; \
    server_name _; \
    root /var/www/html; \
    index index.php index.html; \
    location / { \
        try_files $uri $uri/ /index.php?$query_string; \
    } \
    location ~ \.php$ { \
        include fastcgi_params; \
        fastcgi_pass 127.0.0.1:9000; \
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name; \
    } \
}' > /etc/nginx/http.d/default.conf

# 4. Preparar tus archivos del sistema ETTUR
WORKDIR /var/www/html
COPY . /var/www/html/

# 5. Dar permisos para que PHP pueda leer tus archivos
RUN chown -R www-data:www-data /var/www/html && \
    chmod -R 755 /var/www/html

# 6. Forzar a PHP-FPM a usar el puerto 9000 para hablar con Nginx
RUN sed -i 's/listen = \/run\/php-fpm.sock/listen = 127.0.0.1:9000/g' /usr/local/etc/php-fpm.d/zz-docker.conf || true

EXPOSE ${PORT}

# 7. Comando de arranque: Primero cambia el puerto de Nginx y luego inicia todo
CMD sh -c "sed -i 's/listen 80;/listen '${PORT}';/g' /etc/nginx/http.d/default.conf && php-fpm -D && nginx -g 'daemon off;'"
