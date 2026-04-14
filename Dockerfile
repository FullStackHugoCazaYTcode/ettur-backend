FROM php:8.2-apache

# Install MySQL PDO extension
RUN docker-php-ext-install pdo pdo_mysql

# Enable Apache mod_rewrite
RUN a2enmod rewrite headers

# Set working directory
WORKDIR /var/www/html

# Copy application files
COPY . /var/www/html/

# Create uploads directory
RUN mkdir -p /var/www/html/uploads && \
    chown -R www-data:www-data /var/www/html/uploads && \
    chmod -R 755 /var/www/html/uploads

# Configure Apache
RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

# Set permissions
RUN chown -R www-data:www-data /var/www/html

# Use PORT env variable from Railway
RUN sed -i 's/80/${PORT}/g' /etc/apache2/sites-available/000-default.conf /etc/apache2/ports.conf

EXPOSE ${PORT}

CMD ["apache2-foreground"]
