FROM php:8.2-apache

# 1. Instalar extensiones de MySQL
RUN docker-php-ext-install pdo pdo_mysql

# 2. Habilitar mod_rewrite y headers
RUN a2enmod rewrite headers

# --- SOLUCIÓN AL ERROR MPM ---
# Forzamos la desactivación de mpm_event y mpm_worker que causan el conflicto
# y nos aseguramos de que mpm_prefork (necesario para PHP) esté activo.
RUN a2dismod mpm_event mpm_worker || true && a2enmod mpm_prefork
# -----------------------------

# 3. Configurar el directorio de trabajo
WORKDIR /var/www/html
COPY . /var/www/html/

# 4. Permisos de carpetas
# Aseguramos que Apache tenga control sobre los archivos para poder escribir logs o subir archivos
RUN chown -R www-data:www-data /var/www/html && \
    chmod -R 755 /var/www/html

# 5. Ajustar el puerto para Railway
# Railway asigna un puerto dinámico mediante la variable $PORT
RUN sed -i 's/80/${PORT}/g' /etc/apache2/sites-available/000-default.conf /etc/apache2/ports.conf

# 6. Configuración adicional de Apache
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf
RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

EXPOSE ${PORT}

# Comando para arrancar Apache en primer plano
CMD ["apache2-foreground"]
