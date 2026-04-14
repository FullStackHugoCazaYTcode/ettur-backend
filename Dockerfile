FROM php:8.2-fpm-alpine

# 1. Instalar dependencias
RUN docker-php-ext-install pdo pdo_mysql
RUN apk add --no-cache nginx

# 2. Configurar Nginx
RUN mkdir -p /run/nginx
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

# 3. Preparar archivos
WORKDIR /var/www/html
COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html

# 4. EL CAMBIO: Forzamos a que PHP-FPM escuche en el puerto correcto
RUN sed -i 's/listen = \/usr\/local\/var\/run\/php-fpm.sock/listen = 127.0.0.1:9000/g' /usr/local/etc/php-fpm.d/www.conf

EXPOSE ${PORT}

# Comando de arranque garantizado
CMD sh -c "sed -i 's/listen 80;/listen '${PORT}';/g' /etc/apache2/sites-available/000-default.conf /etc/apache2/ports.conf /etc/nginx/http.d/default.conf && php-fpm -D && nginx -g 'daemon off;'"
