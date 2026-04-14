FROM php:8.2-fpm-alpine

# 1. Instalar extensiones y Nginx
RUN docker-php-ext-install pdo pdo_mysql
RUN apk add --no-cache nginx

# 2. Crear directorios necesarios
RUN mkdir -p /run/nginx /var/www/html/uploads

# 3. Configurar Nginx
RUN echo 'server { \
    listen 80; \
    server_name _; \
    root /var/www/html; \
    index index.php index.html; \
    client_max_body_size 10M; \
    \
    location / { \
        try_files $uri $uri/ /index.php?$query_string; \
    } \
    \
    location ~ \.php$ { \
        include fastcgi_params; \
        fastcgi_pass 127.0.0.1:9000; \
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name; \
        fastcgi_read_timeout 300; \
    } \
    \
    location ~ /\.(env|git|htaccess) { \
        deny all; \
    } \
}' > /etc/nginx/http.d/default.conf

# 4. Copiar proyecto
WORKDIR /var/www/html
COPY . /var/www/html/

# 5. Permisos - usar nobody (existe en Alpine)
RUN chown -R nobody:nobody /var/www/html && \
    chmod -R 755 /var/www/html && \
    chmod -R 777 /var/www/html/uploads

# 6. Configurar PHP-FPM para escuchar en 127.0.0.1:9000
# Y usar usuario nobody (compatible con Alpine)
RUN sed -i 's|listen = .*|listen = 127.0.0.1:9000|g' /usr/local/etc/php-fpm.d/zz-docker.conf && \
    sed -i 's|user = www-data|user = nobody|g' /usr/local/etc/php-fpm.d/www.conf && \
    sed -i 's|group = www-data|group = nobody|g' /usr/local/etc/php-fpm.d/www.conf

# 7. Configurar PHP
RUN echo "upload_max_filesize = 10M" > /usr/local/etc/php/conf.d/uploads.ini && \
    echo "post_max_size = 12M" >> /usr/local/etc/php/conf.d/uploads.ini && \
    echo "display_errors = Off" >> /usr/local/etc/php/conf.d/uploads.ini && \
    echo "date.timezone = America/Lima" >> /usr/local/etc/php/conf.d/uploads.ini

EXPOSE 80

# 8. Arranque: Reemplazar puerto 80 por $PORT de Railway, iniciar PHP-FPM y Nginx
CMD sh -c "sed -i \"s/listen 80;/listen ${PORT:-80};/g\" /etc/nginx/http.d/default.conf && php-fpm -D && nginx -g 'daemon off;'"
