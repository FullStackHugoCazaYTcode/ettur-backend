FROM php:8.2-apache

# Instalar extensión MySQL
RUN docker-php-ext-install pdo pdo_mysql

# Corregir conflicto de MPM (desactivar event, usar prefork)
RUN a2dismod mpm_event && a2enmod mpm_prefork

# Habilitar mod_rewrite y headers
RUN a2enmod rewrite headers

# Configurar PHP
RUN echo "upload_max_filesize = 10M" > /usr/local/etc/php/conf.d/custom.ini && \
    echo "post_max_size = 12M" >> /usr/local/etc/php/conf.d/custom.ini && \
    echo "display_errors = Off" >> /usr/local/etc/php/conf.d/custom.ini && \
    echo "date.timezone = America/Lima" >> /usr/local/etc/php/conf.d/custom.ini

# Permitir .htaccess override
RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

# Copiar proyecto
COPY . /var/www/html/

# Crear carpeta uploads y permisos
RUN mkdir -p /var/www/html/uploads && \
    chown -R www-data:www-data /var/www/html && \
    chmod -R 755 /var/www/html && \
    chmod -R 777 /var/www/html/uploads

# Script de inicio que configura el puerto de Railway
RUN printf '#!/bin/bash\nsed -i "s/Listen 80/Listen ${PORT:-80}/g" /etc/apache2/ports.conf\nsed -i "s/:80/:${PORT:-80}/g" /etc/apache2/sites-available/000-default.conf\napache2-foreground\n' > /usr/local/bin/start.sh && \
    chmod +x /usr/local/bin/start.sh

EXPOSE 80

CMD ["/usr/local/bin/start.sh"]
