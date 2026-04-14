FROM php:8.2-apache

# 1. Instalar extensiones de MySQL
RUN docker-php-ext-install pdo pdo_mysql

# 2. Habilitar mod_rewrite para que funcionen tus rutas de la API
RUN a2enmod rewrite headers

# 3. Configurar el directorio de trabajo
WORKDIR /var/www/html
COPY . /var/www/html/

# 4. Permisos de carpetas
RUN chown -R www-data:www-data /var/www/html && chmod -R 755 /var/www/html

# 5. Ajustar el puerto (Railway usa la variable $PORT)
RUN sed -i 's/80/${PORT}/g' /etc/apache2/sites-available/000-default.conf /etc/apache2/ports.conf

# 6. Forzar que Apache NO cargue módulos extra al arrancar
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf

EXPOSE ${PORT}

# Usamos el comando oficial directo
CMD ["apache2-foreground"]
