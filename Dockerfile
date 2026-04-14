FROM php:8.2-apache

# 1. Instalar extensiones de MySQL
RUN docker-php-ext-install pdo pdo_mysql

# 2. Habilitar mod_rewrite y headers
RUN a2enmod rewrite headers

# --- SOLUCIÓN RADICAL PARA EL ERROR MPM ---
# Forzamos la eliminación de los archivos .load de mpm_event y mpm_worker
# para que Apache ni siquiera intente cargarlos.
RUN rm -f /etc/apache2/mods-enabled/mpm_event.load \
    /etc/apache2/mods-enabled/mpm_event.conf \
    /etc/apache2/mods-enabled/mpm_worker.load \
    /etc/apache2/mods-enabled/mpm_worker.conf || true

# Activamos mpm_prefork explícitamente
RUN a2enmod mpm_prefork
# ------------------------------------------

# 3. Configurar el directorio de trabajo
WORKDIR /var/www/html
COPY . /var/www/html/

# 4. Permisos de carpetas
RUN chown -R www-data:www-data /var/www/html && \
    chmod -R 755 /var/www/html

# 5. Ajustar el puerto para Railway
RUN sed -i 's/80/${PORT}/g' /etc/apache2/sites-available/000-default.conf /etc/apache2/ports.conf

# 6. Configuración de Apache
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf
RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

EXPOSE ${PORT}

# Usamos el comando original para arrancar
CMD ["apache2-foreground"]
