FROM php:8.2-apache

# 1. Instalar extensiones de MySQL
RUN docker-php-ext-install pdo pdo_mysql

# 2. Habilitar módulos necesarios
RUN a2enmod rewrite headers

# --- SOLUCIÓN DEFINITIVA PARA EL ERROR MPM ---
# Borramos cualquier carga de mpm_event y forzamos prefork
RUN rm -f /etc/apache2/mods-enabled/mpm_event.load /etc/apache2/mods-enabled/mpm_event.conf && \
    a2enmod mpm_prefork
# ---------------------------------------------

WORKDIR /var/www/html

COPY . /var/www/html/

# Configurar directorios y permisos
RUN mkdir -p /var/www/html/uploads && \
    chown -R www-data:www-data /var/www/html && \
    chmod -R 755 /var/www/html/uploads

# Configurar Apache para permitir .htaccess
RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

# Ajuste de puerto para Railway
RUN sed -i 's/80/${PORT}/g' /etc/apache2/sites-available/000-default.conf /etc/apache2/ports.conf

EXPOSE ${PORT}

CMD ["apache2-foreground"]
