FROM php:8.2-fpm-alpine

# 1. Instalar dependencias de MySQL y Nginx
RUN docker-php-ext-install pdo pdo_mysql
RUN apk add --no-cache nginx

# 2. Crear carpeta para Nginx
RUN mkdir -p /run/nginx

# 3. Configurar Nginx directamente (Sin usar variables externas en el archivo)
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
        fastcgi_index index.php; \
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name; \
    } \
}' > /etc/nginx/http.d/default.conf

# 4. Preparar archivos
WORKDIR /var/www/html
COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html

# 5. EL TRUCO: Cambiar el 80 por el $PORT justo al arrancar
# Usamos comillas simples para que no falle el comando sed
CMD sh -c "sed -i 's/listen 80;/listen '${PORT}';/g' /etc/nginx/http.d/default.conf && php-fpm -D && nginx -g 'daemon off;'"
