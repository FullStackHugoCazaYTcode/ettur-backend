FROM php:8.2-fpm-alpine

# 1. Instalar extensiones de base de datos
RUN docker-php-ext-install pdo pdo_mysql
RUN apk add --no-cache nginx

# 2. Crear carpeta necesaria para que Nginx arranque
RUN mkdir -p /run/nginx

# 3. Configurar Nginx para que escuche en el puerto 80 internamente
RUN echo 'server { \
    listen 80; \
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

# 4. Preparar archivos del proyecto ETTUR
WORKDIR /var/www/html
COPY . /var/www/html/

# 5. Permisos correctos para Alpine
RUN chown -R www-data:www-data /var/www/html && \
    chmod -R 755 /var/www/html

# 6. Forzar a PHP-FPM a escuchar en el puerto 9000 (Red local)
# En Alpine la ruta es /usr/local/etc/php-fpm.d/zz-docker.conf
RUN sed -i 's/listen = .*/listen = 127.0.0.1:9000/g' /usr/local/etc/php-fpm.d/zz-docker.conf

EXPOSE ${PORT}

# 7. Comando de arranque: Cambia el puerto de Nginx al de Railway e inicia servicios
CMD sh -c "sed -i 's/listen 80;/listen '${PORT}';/g' /etc/nginx/http.d/default.conf && php-fpm -D && nginx -g 'daemon off;'"
