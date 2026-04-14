FROM php:8.2-apache

# 1. Instalar extensiones de MySQL
RUN docker-php-ext-install pdo pdo_mysql

# 2. Habilitar módulos de Apache
RUN a2enmod rewrite headers

# --- ESTA ES LA PARTE QUE SOLUCIONA EL ERROR ---
# Desactiva mpm_event y mpm_worker, y asegura que mpm_prefork esté activo
RUN a2dismod mpm_event mpm_worker || true && a2enmod mpm_prefork
# -----------------------------------------------

WORKDIR /var/www/html

COPY . /var/www/html/

RUN mkdir -p /var/www/html/uploads && \
    chown -R www-data:www-data /var/www/html/uploads && \
    chmod -R 755 /var/www/html/uploads

RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

RUN chown -R www-data:www-data /var/www/html

# Ajuste para que Apache escuche en el puerto que Railway le asigne
RUN sed -i 's/80/${PORT}/g' /etc/apache2/sites-available/000-default.conf /etc/apache2/ports.conf

EXPOSE ${PORT}

CMD ["apache2-foreground"]
