FROM php:8.2-cli

RUN docker-php-ext-install pdo pdo_mysql

RUN echo "upload_max_filesize = 10M" > /usr/local/etc/php/conf.d/custom.ini && \
    echo "post_max_size = 12M" >> /usr/local/etc/php/conf.d/custom.ini && \
    echo "max_execution_time = 120" >> /usr/local/etc/php/conf.d/custom.ini && \
    echo "max_input_time = 120" >> /usr/local/etc/php/conf.d/custom.ini && \
    echo "memory_limit = 128M" >> /usr/local/etc/php/conf.d/custom.ini && \
    echo "file_uploads = On" >> /usr/local/etc/php/conf.d/custom.ini && \
    echo "display_errors = Off" >> /usr/local/etc/php/conf.d/custom.ini && \
    echo "date.timezone = America/Lima" >> /usr/local/etc/php/conf.d/custom.ini

WORKDIR /var/www/html
COPY . /var/www/html/

RUN mkdir -p /var/www/html/uploads && \
    chmod -R 777 /var/www/html/uploads

CMD php -S 0.0.0.0:${PORT:-8080} /var/www/html/router.php
